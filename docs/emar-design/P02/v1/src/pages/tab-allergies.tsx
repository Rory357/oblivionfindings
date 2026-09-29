/* Allergies & alerts. Allergies are edited only on the health profile and a
 * lead confirms the list (Stephan, 30 Sep); this section shows both of today's
 * sources (as the EM-07 fix reads them) and links there. Chart alerts absorb
 * ManageAlertsDialog + WarningsDialog; interactions absorb InteractionsDialog. */
import { EntityContextMenu, compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Label } from '@/components/ui/label';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import { StatusBadge } from '@/components/ui/status-badge';
import { Switch } from '@/components/ui/switch';
import { Activity, BellOff, BellRing, CheckCircle2, ClipboardList, FileText, Info, LockKeyhole, Pencil, Pill, Plus, ShieldAlert, TriangleAlert, ArrowUpRight } from 'lucide-react';
import { useState, type MouseEvent } from 'react';
import { FACTS, INTERACTIONS, type AllergyEntry, type ChartAlert, type Interaction, type PersonId } from '../data';
import { PEOPLE } from '../p01/data';
import { DesignNote, Notice } from '../p01/ui';
import { useDlg, useP02 } from '../store';
import { AllergySummary, CONCEALED, ConcealedCaption, SectionCard, Wrap, useAllergy } from '../ui';

function useCtx() {
    const [ctx, setCtx] = useState<{ x: number; y: number; title: string; items: MenuItem[] } | null>(null);
    const openAt = (e: MouseEvent, title: string, items: MenuItem[]) => {
        e.preventDefault();
        const b = (e.currentTarget as HTMLElement).getBoundingClientRect();
        const kb = e.clientX === 0 && e.clientY === 0;
        setCtx({ x: kb ? b.left + 12 : e.clientX, y: kb ? b.top + 12 : e.clientY, title, items });
    };
    return { openAt, node: ctx ? <EntityContextMenu x={ctx.x} y={ctx.y} icon={ShieldAlert} title={ctx.title} items={ctx.items} onClose={() => setCtx(null)} /> : null };
}
export const ALERT_TYPE: Record<ChartAlert['type'], string> = { warfarin: 'Warfarin / INR', paper: 'Paper prescription on file', warning: 'Chart warning' };

export function AllergiesTab({ pid, view }: { pid: PersonId; view: string }) {
    const s = useP02();
    if (s.route.state === 'loading')
        return (
            <Card className="p-4">
                <SkeletonTable rows={4} columns={4} />
            </Card>
        );
    if (view === 'alerts') return <Alerts pid={pid} />;
    if (view === 'interactions') return <Interactions pid={pid} />;
    return <Allergies pid={pid} />;
}

/* ───────────── Allergies ───────────── */
function Allergies({ pid }: { pid: PersonId }) {
    const s = useP02();
    const a = useAllergy(pid);
    const ctx = useCtx();
    const cid = String(FACTS[pid].clientId);
    const toProfile = (dlg?: string) => s.go(`/operations/clients/${cid}`, { tab: 'medical', dlg });
    const canReview = s.can('clients.update') && s.can('orders.manage');
    const action = canReview ? (
        <Button size="sm" variant="link" onClick={() => toProfile('allergy-review')}>
            <CheckCircle2 className="size-4" /> Review on the health profile <ArrowUpRight className="size-4" aria-hidden="true" />
        </Button>
    ) : (
        <Button size="sm" variant="link" className="px-0" onClick={() => toProfile()}>
            Open the health profile <ArrowUpRight className="size-4" aria-hidden="true" />
        </Button>
    );
    const menu = (): MenuItem[] => compactMenu([canReview ? { label: 'Edit on the health profile', icon: Pencil, onClick: () => toProfile('allergy-review') } : { label: 'Open the health profile', icon: FileText, onClick: () => toProfile() }]);
    return (
        <>
            <AllergySummary pid={pid} action={action} />
            {a.status === 'recorded' ? (
                <section aria-label="Recorded allergies" className="flex flex-col gap-2.5">
                    <ListCaption title="Recorded allergies" caption={`${a.entries.length} of ${a.entries.length} shown · both of today’s lists are read`} />
                    <EntityTable<AllergyEntry>
                        rows={a.entries}
                        rowKey={(e) => e.allergen}
                        identityLabel="Allergy"
                        identityWidth="1.6fr"
                        rowHeight="content"
                        minWidth={900}
                        identity={(e) => ({ icon: ShieldAlert, name: e.allergen, subline: e.reaction ? `Reaction: ${e.reaction}` : 'Reaction not recorded' })}
                        columns={[
                            { key: 'sev', label: 'Severity', width: '1fr', cell: (e) => (e.severity === 'Severe' ? <StatusBadge variant="critical" className="rounded-[8px]">Severe</StatusBadge> : e.severity === 'Moderate' ? <StatusBadge variant="warning" className="rounded-[8px]">Moderate</StatusBadge> : e.severity === 'Mild' ? <StatusBadge variant="neutral" className="rounded-[8px]">Mild</StatusBadge> : <StatusBadge variant="warning" className="rounded-[8px]">Severity not recorded</StatusBadge>) },
                            { key: 'src', label: 'Where it’s recorded today', width: '1.4fr', cell: (e) => <span className="text-[12.5px]">{e.source === 'Both lists' ? 'Health profile and the medication allergy list' : e.source}</span> },
                            { key: 'by', label: 'Recorded', width: '1.4fr', cell: (e) => <span className="text-[12.5px]">{e.recorded}</span> },
                        ]}
                        actionsFor={menu}
                        onOpen={() => (canReview ? toProfile('allergy-review') : toProfile())}
                        onRowContextMenu={(e, x) => ctx.openAt(e, x.allergen, menu())}
                    />
                    {ctx.node}
                </section>
            ) : a.status === 'none' ? (
                <Card className="p-2">
                    <EmptyState variant="compact" icon={ShieldAlert} title={`Nothing recorded for ${PEOPLE[pid].pref} — not the same as no allergies`} description="A house lead or clinical lead checks with the person, family and GP record, then records the allergies or “No known allergies” on the health profile." />
                </Card>
            ) : null}
            <DesignNote title="Design note — one allergy list (Stephan, 30 Sep)">
                <p>Allergies are edited only on the health profile (client profile › Health &amp; safety › Medical). The medication allergy list — written only by the mobile API today, with severity — is merged into it at build, so “Where it’s recorded today” disappears. A house lead or clinical lead confirms the list; until then it reads “Not reviewed”. An empty list never reads “No known allergies”.</p>
            </DesignNote>
        </>
    );
}

/* ───────────── Chart alerts (ManageAlertsDialog + WarningsDialog absorbed) ───────────── */
function Alerts({ pid }: { pid: PersonId }) {
    const s = useP02();
    const dlg = useDlg();
    const ctx = useCtx();
    const lead = s.can('orders.manage');
    const showResolved = s.route.q.get('resolved') === '1';
    const mine = s.alerts.filter((a) => a.pid === pid);
    const hidden = s.cdView ? 0 : mine.filter((a) => a.cd && (showResolved || !a.resolved)).length;
    const rows = mine.filter((a) => showResolved || !a.resolved);
    const paused = s.paused[pid];
    const menu = (a: ChartAlert): MenuItem[] =>
        a.cd && !s.cdView
            ? [{ label: 'Why is this hidden?', icon: Info, onClick: () => s.toast('info', `Only people with controlled-medicine access can see this alert’s details. ${CONCEALED.ask}`) }]
            : compactMenu([
                  lead && !a.resolved && { label: 'Edit alert', icon: Pencil, onClick: () => dlg.open(`alert:${a.id}`) },
                  lead && !a.resolved && { label: 'Mark resolved', icon: CheckCircle2, onClick: () => dlg.open(`resolve:${a.id}`) },
                  !lead && { label: 'Who can change alerts?', icon: Info, onClick: () => s.toast('info', 'House leads and clinical leads add, change and resolve chart alerts. Jordan Tipene is on shift today.') },
              ]);
    return (
        <>
            <SectionCard
                eyebrow="Alerts to staff"
                title={`Due and late dose alerts for ${PEOPLE[pid].pref}`}
                icon={paused ? BellOff : BellRing}
                right={
                    <div className="flex items-center gap-2.5">
                        <Label htmlFor="p02-dose-alerts" className="text-sm">
                            {paused ? 'Paused' : 'On'}
                        </Label>
                        <Switch
                            id="p02-dose-alerts"
                            checked={!paused}
                            disabled={!lead}
                            onCheckedChange={(on) => (on ? (s.setPaused(pid, null), s.toast('success', `Due and late dose alerts are back on for ${PEOPLE[pid].pref}.`)) : dlg.open('pause'))}
                            aria-describedby="p02-dose-alerts-help"
                        />
                    </div>
                }
            >
                <p id="p02-dose-alerts-help" className="text-sm text-muted-foreground">
                    {paused
                        ? `Paused ${paused.at} by ${paused.by} · ${paused.basis} · “${paused.reason}”. Staff aren’t alerted when ${PEOPLE[pid].pref}’s doses are due or late. The chart still shows every dose.`
                        : `Staff on shift are alerted when a dose is due and when it’s late (routing is set in Medication › Settings). Pausing needs a basis and a reason.`}
                    {!lead ? ' Only house leads and clinical leads can pause them — ask Jordan Tipene.' : ''}
                </p>
            </SectionCard>
            {paused ? (
                <Notice tone="warning" icon={BellOff} title="Due and late dose alerts are paused">
                    Paused alerts weaken a safety check. The pause shows on the Overview for leads until it’s turned back on.
                </Notice>
            ) : null}
            <section aria-label="Chart alerts" className="flex flex-col gap-2.5">
                <ListCaption
                    title="Chart alerts"
                    caption={
                        <>
                            {rows.length} of {rows.length} shown{showResolved ? ' · including resolved' : ''}{hidden ? ' · ' : ''}
                            <ConcealedCaption n={hidden} />
                        </>
                    }
                    right={
                        lead ? (
                            <Button size="sm" onClick={() => dlg.open('alert:new')}>
                                <Plus className="size-4" /> Add chart alert
                            </Button>
                        ) : null
                    }
                />
                {rows.length ? (
                    <EntityTable<ChartAlert>
                        rows={rows}
                        rowKey={(a) => a.id}
                        identityLabel="Alert"
                        identityWidth="2.2fr"
                        rowHeight="content"
                        minWidth={1000}
                        identity={(a) => (a.cd && !s.cdView ? { icon: LockKeyhole, name: 'Controlled-medicine alert', subline: CONCEALED.subline } : { icon: a.type === 'warfarin' ? Activity : a.type === 'paper' ? ClipboardList : TriangleAlert, name: a.title, subline: <Wrap>{a.detail}</Wrap> })}
                        columns={[
                            { key: 'type', label: 'Type', width: '1.1fr', cell: (a) => (a.cd && !s.cdView ? <span className="text-muted-foreground">—</span> : <span className="text-[12.5px]">{ALERT_TYPE[a.type]}</span>) },
                            {
                                key: 'open',
                                label: 'Shown when the chart opens',
                                width: '1.2fr',
                                cell: (a) =>
                                    a.cd && !s.cdView ? (
                                        <span className="text-muted-foreground">—</span>
                                    ) : lead && !a.resolved ? (
                                        <span className="flex items-center gap-2" onClick={(e) => e.stopPropagation()}>
                                            <Switch checked={a.onOpen} onCheckedChange={(on) => (on ? (s.saveAlert({ ...a, onOpen: true }), s.toast('success', `“${a.title}” now shows when the chart opens.`)) : dlg.open(`onopen-off:${a.id}`))} aria-label={`Show “${a.title}” when the chart opens`} />
                                            <span className="text-[12.5px]">{a.onOpen ? 'Yes' : 'No'}</span>
                                        </span>
                                    ) : (
                                        <span className="text-[12.5px]">{a.onOpen ? 'Yes' : 'No'}</span>
                                    ),
                            },
                            { key: 'by', label: 'Added', width: '1fr', cell: (a) => <span className="text-[12.5px]">{a.on} · {a.by}</span> },
                            { key: 'st', label: 'Status', width: '1fr', cell: (a) => (a.resolved ? <StatusBadge variant="neutral" className="rounded-[8px]">Resolved {a.resolved.on}</StatusBadge> : <StatusBadge variant="warning" className="rounded-[8px]">Active</StatusBadge>) },
                        ]}
                        actionsFor={menu}
                        onOpen={(a) => (a.cd && !s.cdView ? menu(a)[0].onClick?.() : lead && !a.resolved ? dlg.open(`alert:${a.id}`) : dlg.open('warnings'))}
                        onRowContextMenu={(e, a) => ctx.openAt(e, a.cd && !s.cdView ? 'Controlled-medicine alert' : a.title, menu(a))}
                        mutedFor={(a) => !!a.resolved}
                    />
                ) : (
                    <Card className="p-2">
                        <EmptyState variant="compact" icon={BellRing} title="No chart alerts" description={lead ? 'Add one for anything staff must read before recording.' : 'Leads add alerts for anything staff must read before recording.'} />
                    </Card>
                )}
                {ctx.node}
            </section>
            <DesignNote title="Design note — chart alerts">
                <p>Today’s Manage alerts dialog can only add (no list, edit or resolve) and is reachable only once an alert already exists; the warnings dialog’s “Acknowledge &amp; continue” isn’t saved. Here the alerts are a list, “shown when the chart opens” is a switch, and reading them is recorded against the reader.</p>
            </DesignNote>
        </>
    );
}

/* ───────────── Interactions (recorded only — no interaction database) ───────────── */
function Interactions({ pid }: { pid: PersonId }) {
    const s = useP02();
    const dlg = useDlg();
    const ctx = useCtx();
    const all = INTERACTIONS.filter((x) => x.pid === pid);
    const hidden = s.cdView ? 0 : all.filter((x) => x.cd).length;
    const menu = (x: Interaction): MenuItem[] => (x.cd && !s.cdView ? [{ label: 'Why is this hidden?', icon: Info, onClick: () => s.toast('info', `${CONCEALED.subline}. ${CONCEALED.ask}`) }] : [{ label: 'View details', icon: Info, onClick: () => dlg.open(`interaction:${x.id}`) }]);
    return (
        <>
            <Notice tone="neutral" icon={Info} title="Only interactions someone recorded are shown">
                The app doesn’t check a drug-interaction database. These come from the pharmacy or prescriber. If you’re unsure about a combination, ask the pharmacist before giving.
            </Notice>
            <section aria-label="Recorded interactions" className="flex flex-col gap-2.5">
                <ListCaption
                    title="Recorded interactions"
                    caption={
                        <>
                            {all.length} of {all.length} shown{hidden ? ' · ' : ''}
                            <ConcealedCaption n={hidden} />
                        </>
                    }
                    right={s.can('orders.manage') ? <Button size="sm" variant="link" onClick={() => s.go('/emar/prescriptions', { client_id: String(FACTS[pid].clientId) })}>Recorded with the order in Orders &amp; reviews <ArrowUpRight className="size-4" aria-hidden="true" /></Button> : null}
                />
                {all.length ? (
                    <EntityTable<Interaction>
                        rows={all}
                        rowKey={(x) => x.id}
                        identityLabel="Medicines"
                        identityWidth="2fr"
                        rowHeight="content"
                        minWidth={900}
                        identity={(x) => (x.cd && !s.cdView ? { icon: LockKeyhole, name: 'Includes a controlled medicine', subline: CONCEALED.subline } : { icon: Pill, name: `${x.a} with ${x.b}`, subline: <Wrap>{x.note}</Wrap> })}
                        columns={[
                            { key: 'sev', label: 'Severity (as recorded)', width: '1fr', cell: (x) => (x.cd && !s.cdView ? <span className="text-muted-foreground">—</span> : <StatusBadge variant={x.severity === 'Major' ? 'critical' : x.severity === 'Moderate' ? 'warning' : 'neutral'} className="rounded-[8px]">{x.severity}</StatusBadge>) },
                            { key: 'by', label: 'Recorded by', width: '1.2fr', cell: (x) => <span className="text-[12.5px]">{x.cd && !s.cdView ? '—' : x.by}</span> },
                        ]}
                        actionsFor={menu}
                        onOpen={(x) => menu(x)[0].onClick?.()}
                        onRowContextMenu={(e, x) => ctx.openAt(e, x.cd && !s.cdView ? 'Controlled medicine' : `${x.a} with ${x.b}`, menu(x))}
                    />
                ) : (
                    <Card className="p-2">
                        <EmptyState variant="compact" icon={Activity} title="No interactions recorded" description="Not the same as “no interactions” — ask the pharmacist if you’re unsure." />
                    </Card>
                )}
                {ctx.node}
            </section>
            <DesignNote title="Design note — interactions">
                <p>Today the page receives every active medicine’s interaction names, including controlled medicines the viewer can’t see (EM-12 leak, AUDIT.md), and matches names by text fragment. The record shows only recorded pairs and hides controlled ones.</p>
            </DesignNote>
        </>
    );
}
