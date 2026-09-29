/* Settings dialogs. Simple dialogs are Fleet Settings’ `Modal` (480, bordered header,
 * scrolling body, muted footer); consequential single actions use ConfirmDialog; any
 * add/edit with sections is WizardShell with a DiscardDraftDialog guard. */
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { TimePicker } from '@/components/fleet-assets/maintenance/time-picker';
import { DiscardDraftDialog } from '@/components/governance/DiscardDraftDialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { ChipMulti, Field, InfoCard, SelectInput, StepHead, TilePicker } from '@/components/wizard/primitives';
import { ReviewCard, ReviewRow, WizardShell, WizardStepPane, WizardSuccessPane } from '@/components/wizard/shell';
import { Modal, Notice } from '@/pages/fleet-assets/settings/_ui';
import { AlertTriangle, ArrowUpRight, Bell, Building2, CalendarDays, Check, ChevronLeft, LockKeyhole, ChevronRight, Phone, ClipboardCheck, Clock, History, Home, Layers, Pill, Repeat, Shield, User, Users } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { ALERTS, ALERT_PEOPLE, CLASSES, RECIPIENT_GROUPS, DAYS, EMPLOYED, HOUSES, HOUSE_KEYS, ONCALL_ROSTER, describeOnCall, employedAt, employee, resolveOnCall, type OnCallRule, MATCH, MEDLIST, NZULM, PEOPLE, ROUTES, STAFF, type HouseKey, type Rule, type Tpl } from './data';
import {
    GROUPS, VIEW_LABEL, allChanges, allHistory, canHouse, canOrg, canRuleScope, canTemplates, daysText, fmtT, givenAbility, has, logChange, myHouses, ruleItems,
    ruleNeeds, ruleOverlaps, ruleSentence, ruleWhat, stamp, staffNow, toMin, tplOverlaps, useStore, viewChanges, viewGroups, type ViewKey,
} from './model';
import { SET_VIEWS, settingsHref, useNav } from './nav';
import { peopleItems } from './settings';
import { Choice, NumberInput, OnOff, RecordPicker, type PickItem } from './ui';

const clone = <T,>(o: T): T => JSON.parse(JSON.stringify(o));
const Recorded = () => <p className="text-caption">Recorded in the change history and the audit log with your name and the time.</p>;

/* ── Review → save (Fleet “Review … changes”), with failure and conflict states ── */
export function ReviewChanges({ view }: { view: ViewKey }) {
    const { m, set, flash } = useStore();
    const { close } = useNav();
    const [state, setState] = useState<'' | 'fail' | 'conflict'>('');
    const saved = useRef(false); // after a save, focus goes to the page’s status notice, not back to the button
    const ch = viewChanges(m, view);
    const byG = ch.reduce<Record<string, typeof ch>>((a, c) => { (a[c.g] = a[c.g] || []).push(c); return a; }, {});
    const cdOff = !!byG.cdw && (m.draft.cdw.org === 'off' || m.draft.cdw.kowhai === 'off' || m.draft.cdw.rimu === 'off');
    const save = () => {
        if (m.demo.settings === 'offline') return;
        if (m.demo.save === 'fail') { setState('fail'); set((d) => { d.demo.save = 'ok'; }); return; }
        if (m.demo.save === 'conflict') { setState('conflict'); return; }
        set((d) => {
            ch.forEach((c) => {
                logChange(d, { area: view as 'rules', sec: GROUPS[c.g].sec(c.k), scope: c.g === 'alertExtra' ? HOUSES[c.k.split('.')[0] as HouseKey] : c.k === 'kowhai' || c.k === 'rimu' ? HOUSES[c.k] : 'All houses', what: c.label, from: c.from, to: c.to, ev: GROUPS[c.g].ev });
                if (c.g === 'ea') d.eaSaved = stamp(d); else d.setBy[c.g][c.k] = stamp(d);
                if (c.g === 'timing' && c.k === 'escalN') d.setBy.timing.escalDays = stamp(d);
            });
            viewGroups(view).forEach((g) => { (d.saved as Record<string, unknown>)[g] = clone(d.draft[g]); Object.keys(d.pendingOn).forEach((k) => { if (k.startsWith(`${g}.`)) delete d.pendingOn[k]; }); });
        });
        saved.current = true;
        close();
        flash(`${ch.length} ${ch.length === 1 ? 'change' : 'changes'} saved. ${GROUPS[ch[0].g].effect}.`);
    };
    return (
        <Modal title={`Review ${VIEW_LABEL[view].toLowerCase()} changes`} description={`${ch.length} ${ch.length === 1 ? 'change' : 'changes'} · nothing applies until you save.`} onClose={close} onCloseAutoFocus={(e) => { if (saved.current) e.preventDefault(); }}
            footer={state === 'conflict'
                ? <><Button variant="outline" onClick={close}>Keep editing</Button><Button onClick={() => { set((d) => { d.demo.save = 'ok'; }); close(); flash('Refreshed. Rangi Parata’s change is shown; your changes are kept — review and save again.'); }}>Refresh and review</Button></>
                : <><Button variant="outline" onClick={close}>Keep editing</Button><Button variant={cdOff ? 'destructive' : 'default'} onClick={save} disabled={m.demo.settings === 'offline'}>{state === 'fail' ? 'Try again' : 'Save changes'}</Button></>}>
            {state === 'fail' ? <Notice><span><b>Couldn’t save — nothing was changed.</b> Check your connection and try again. Your changes are kept.</span></Notice> : null}
            {state === 'conflict' ? <Notice><span><b>Settings changed elsewhere.</b> Rangi Parata saved a change in this view at 9:10 am. Your changes are kept — refresh to see theirs, then save again.</span></Notice> : null}
            {m.demo.settings === 'offline' ? <Notice><span>You’re offline — reconnect to save. Your changes are kept.</span></Notice> : null}
            {Object.entries(byG).map(([g, list]) => (
                <ReviewCard key={g} icon={g === 'timing' ? Clock : g === 'elig' ? ClipboardCheck : g === 'pin' ? Shield : g === 'cdw' ? Shield : g === 'alerts' || g === 'alertExtra' ? Bell : Layers} title={GROUPS[g as keyof typeof GROUPS].effect}>
                    {list.map((c) => <ReviewRow key={c.k} label={c.label} value={<><span className="text-muted-foreground line-through decoration-1">{c.from}</span> → <b>{c.to}</b></>} />)}
                </ReviewCard>
            ))}
            {cdOff ? <InfoCard icon={AlertTriangle} tone="crit"><b>Controlled doses will be recorded without a witness there.</b> Check this with clinical governance first. For a short gap, a time-limited override is safer.</InfoCard> : null}
            <Recorded />
        </Modal>
    );
}
export function DiscardView({ view }: { view: ViewKey }) {
    const { m, set, flash } = useStore();
    const { close } = useNav();
    const ch = viewChanges(m, view);
    return (
        <ConfirmDialog open onClose={close} title={`Discard ${VIEW_LABEL[view].toLowerCase()} changes?`} confirmText="Discard changes" cancelText="Keep editing"
            description={<><p>{ch.length} unsaved {ch.length === 1 ? 'change' : 'changes'} will be lost. Saved settings stay as they are.</p><ul className="mt-2 list-disc pl-5">{ch.map((c) => <li key={c.g + c.k}><b>{c.label}:</b> {c.to}</li>)}</ul></>}
            onConfirm={() => { set((d) => { viewGroups(view).forEach((g) => { (d.draft as Record<string, unknown>)[g] = clone(d.saved[g]); Object.keys(d.pendingOn).forEach((k) => { if (k.startsWith(`${g}.`)) delete d.pendingOn[k]; }); }); }); flash('Unsaved changes discarded. Saved settings are unchanged.'); }} />
    );
}
export function UnsavedList() {
    const { m } = useStore();
    const { close, go } = useNav();
    const ch = allChanges(m);
    const views = [...new Set(ch.map((c) => GROUPS[c.g].view))];
    return (
        <Modal title="Unsaved changes" description="Nothing applies until you review and save each view." onClose={close}>
            {views.map((v) => (
                <ReviewCard key={v} icon={Layers} title={VIEW_LABEL[v]}>
                    {ch.filter((c) => GROUPS[c.g].view === v).map((c) => <ReviewRow key={c.g + c.k} label={c.label} value={c.to} />)}
                    <Button variant="outline" size="sm" className="mt-3" onClick={() => { close(); go(settingsHref(v)); }}>Go to {VIEW_LABEL[v]}<ArrowUpRight /></Button>
                </ReviewCard>
            ))}
        </Modal>
    );
}
/** Fleet’s guard, wording unchanged except the browser-storage clause (this preview keeps nothing). */
export function LeaveGuard({ onLeave }: { onLeave: () => void }) {
    const { m } = useStore();
    const { close } = useNav();
    return (
        <Modal title="Leave with an unsaved draft?" description="Your saved settings will stay unchanged." onClose={close}
            footer={<><Button variant="outline" onClick={close} autoFocus>Keep editing</Button><Button onClick={onLeave}>Leave page</Button></>}>
            <p className="text-subtle">Review and save your changes before leaving if you want them applied.</p>
            <ul className="list-disc space-y-1 pl-5 text-[13px]">{allChanges(m).map((c) => <li key={c.g + c.k}><b>{VIEW_LABEL[GROUPS[c.g].view]}</b> — {c.label}: {c.to}</li>)}</ul>
        </Modal>
    );
}
export function HistDetail({ id }: { id: string }) {
    const { m } = useStore();
    const { close, go } = useNav();
    const h = allHistory(m).find((x) => x.id === id);
    if (!h) return <NotFound what="change" />;
    return (
        <Modal title={h.what} description={`${h.when} NZDT · ${h.who}${h.note ? ` · ${h.note}` : ''}`} onClose={close}
            footer={<><Button variant="outline" onClick={close}>Close</Button><Button onClick={() => { close(); go(settingsHref(h.area, h.sec)); }}>Go to the setting<ArrowUpRight /></Button></>}>
            <ReviewCard icon={History} title="The change"><ReviewRow label="Before" value={h.from || '—'} /><ReviewRow label="After" value={<b>{h.to}</b>} /></ReviewCard>
            <ReviewCard icon={Building2} title="Where and when"><ReviewRow label="Area" value={`${VIEW_LABEL[h.area]} › ${SET_VIEWS[h.area].secs.find(([k]) => k === h.sec)?.[1] ?? ''}`} /><ReviewRow label="Where" value={h.scope} /><ReviewRow label="Recorded as" value={<code className="text-[12px]">{h.ev}</code>} /></ReviewCard>
        </Modal>
    );
}
export function NotFound({ what = 'record' }: { what?: string }) {
    const { close } = useNav();
    return <Modal title="We can’t show this record" description="It may not exist, or it may not be available to you." onClose={close}><p className="text-subtle">Check the link, or choose the {what} from the list.</p></Modal>;
}

/* ── Medicine rules: the P00 v5 builder on WizardShell (house scopes for house managers — answer 1) ── */
const RULE_STEPS = [{ key: 'what', label: 'What it applies to', blurb: 'Medicines and houses', icon: Pill }, { key: 'needs', label: 'What it requires', blurb: 'Before a dose is saved', icon: ClipboardCheck }, { key: 'review', label: 'Review & save', blurb: 'Check who it affects', icon: Check }] as const;
function RulePreview({ r }: { r: Pick<Rule, 'match' | 'value' | 'scope'> }) {
    const { m } = useStore();
    const seeCd = has(m.persona, 'cd.view');
    const shown = ruleItems(r).filter((x) => seeCd || !x.cd);
    const people = [...new Set(shown.map((x) => x.pid))], meds = [...new Set(shown.map((x) => x.med))];
    return (
        <ReviewCard icon={Users} title={`Would apply now to ${meds.length} ${meds.length === 1 ? 'medicine' : 'medicines'} for ${people.length} ${people.length === 1 ? 'person' : 'people'}`}>
            {shown.length ? shown.map((x, i) => <ReviewRow key={i} label={`${PEOPLE[x.pid].pref} ${PEOPLE[x.pid].surname}`} value={`${x.med} · ${HOUSES[x.house]}`} />) : <p className="text-caption">No current orders match. The rule applies when a matching order is added.</p>}
            {seeCd ? null : <p className="text-caption mt-2">Showing medicines your role can see.</p>}
        </ReviewCard>
    );
}
export function RuleWizard({ id }: { id: string }) {
    const { m, set } = useStore();
    const { close } = useNav();
    const p = m.persona, src = m.rules.find((r) => r.id === id);
    const scopes = (['all', ...HOUSE_KEYS] as ('all' | HouseKey)[]).filter((s) => canRuleScope(s, p));
    const [r, setR] = useState<Rule>(() => (src ? clone(src) : { id: '', match: 'name', value: '', scope: scopes[0] ?? 'kowhai', countersign: false, obs: [], active: true, by: '', when: '' }));
    const [step, setStep] = useState(0), [err, setErr] = useState<'' | 'value' | 'needs'>(''), [dirty, setDirty] = useState(false), [saved, setSaved] = useState(false), [guard, setGuard] = useState(false);
    if (id !== 'new' && !src) return <NotFound what="rule" />;
    const up = (patch: Partial<Rule>) => { setR({ ...r, ...patch }); setDirty(true); setErr(''); };
    const valueOk = r.match === 'controlled' || !!String(r.value).trim(), needsOk = r.countersign || r.obs.length > 0;
    const next = () => { if (step === 0 && !valueOk) return setErr('value'); if (step === 1 && !needsOk) return setErr('needs'); setErr(''); setStep(Math.min(2, step + 1)); };
    const save = () => {
        if (!valueOk) { setStep(0); return setErr('value'); }
        if (!needsOk) { setStep(1); return setErr('needs'); }
        set((d) => {
            const data: Rule = { ...r, value: r.match === 'name' ? String(r.value).trim() : r.value, by: stamp(d).split(',')[0], when: '29 Sep 2026', paused: r.active ? '' : `Paused 29 Sep 2026 by ${stamp(d).split(',')[0]}` };
            if (src) Object.assign(d.rules.find((x) => x.id === src.id)!, data); else d.rules.push({ ...data, id: `mr${d.rules.length + 1}` });
            logChange(d, { area: 'rules', sec: 'medicines', scope: HOUSES[r.scope], what: src ? 'Medicine rule changed' : 'Medicine rule added', from: src ? ruleSentence(src) : '—', to: ruleSentence(data), ev: src ? 'medicationadminrule.update' : 'medicationadminrule.create' });
        });
        setSaved(true); setDirty(false);
    };
    const onClose = () => (dirty && !saved ? setGuard(true) : close());
    const medItems: PickItem[] = [...new Set(MEDLIST.filter((x) => has(p, 'cd.view') || !x.cd).map((x) => x.med))].map((n) => ({ id: n, name: n, sub: MEDLIST.filter((x) => x.med === n).map((x) => x.route).filter((v, i, a) => a.indexOf(v) === i).join(', '), ok: true }));
    const scopeItems: PickItem[] = scopes.map((s) => ({ id: s, name: HOUSES[s], sub: s === 'all' ? 'Every house, now and in future' : 'This house only', ok: true }));
    const overlaps = ruleOverlaps(m, r);
    const pct = Math.round(([valueOk, !!r.scope, needsOk, true].filter(Boolean).length / 4) * 100);
    return (
        <>
            <WizardShell open onClose={onClose} title={src ? 'Edit medicine rule' : 'Add a medicine rule'} description="A rule that asks for a second person or an observation before a dose is saved."
                railIcon={Pill} railTitle={src ? 'Edit medicine rule' : 'Add a medicine rule'} railSub="Settings › Medication rules" steps={RULE_STEPS} stepIndex={step} onStepClick={(i) => { setErr(''); setStep(i); }} pct={pct}
                success={saved ? <WizardSuccessPane title={src ? 'Rule updated' : 'Rule added'} blurb={<>{ruleSentence(r)}<br />{r.active ? 'Applies from the next dose saved.' : 'Saved as paused — it doesn’t apply until someone turns it on.'} Recorded in the change history.</>} actions={<Button onClick={close} autoFocus>Done</Button>} /> : undefined}
                footerStart={<div className="flex gap-2"><Button variant="outline" onClick={onClose}>Cancel</Button>{step > 0 ? <Button variant="outline" onClick={() => setStep(step - 1)}><ChevronLeft />Back</Button> : null}</div>}
                footerEnd={step < 2 ? <Button onClick={next}>Continue<ChevronRight /></Button> : <Button onClick={save}><Check />{src ? 'Save changes' : 'Save rule'}</Button>}>
                <WizardStepPane key={step}>
                    {step === 0 ? (
                        <div className="space-y-5">
                            <StepHead icon={Pill} title="What it applies to" blurb="Choose the medicines and where the rule applies." />
                            <Field label="Match medicines by" required>
                                <TilePicker value={r.match} cols={3} onChange={(v) => up({ match: v, value: '' })} options={Object.entries(MATCH).map(([key, x]) => ({ key, label: x.l, description: x.d, icon: key === 'controlled' ? Shield : key === 'cls' ? Layers : Pill }))} />
                            </Field>
                            <div className="flex flex-wrap gap-2" aria-label="Design notes">{Object.values(MATCH).filter((x) => x.isNew).map((x) => <StatusBadge key={x.l} variant="info" size="sm">Design note — {x.l}: {x.isNew!.replace(/^New — /, 'new, ')}</StatusBadge>)}</div>
                            {r.match === 'name' ? <RecordPicker id="rw-v" label="Medicine" required value={r.value} items={medItems} onChange={(v) => up({ value: v })} placeholder="Search and choose a medicine" search="Search medicines…" error={err === 'value' ? 'Choose which medicines this rule applies to.' : undefined} foot="Matches the medicine’s name on the order." />
                                : r.match === 'controlled' ? <InfoCard icon={Shield}><b>Any controlled medicine</b> — from the order’s controlled flag.</InfoCard>
                                    : <Field label={r.match === 'route' ? 'Route' : r.match === 'nzulm' ? 'Product (NZULM code)' : 'Type or class'} required error={err === 'value' ? 'Choose which medicines this rule applies to.' : undefined}>
                                        <SelectInput value={r.value} onChange={(v) => up({ value: v })} placeholder="Choose" options={(r.match === 'route' ? ROUTES.map((x) => [x, x]) : r.match === 'nzulm' ? NZULM.map(([c, n]) => [c, `${c} — ${n} (test code)`]) : CLASSES.map((x) => [x, x])).map(([value, label]) => ({ value, label }))} />
                                    </Field>}
                            <RecordPicker id="rw-scope" label="Where it applies" required value={r.scope} items={scopeItems} onChange={(v) => up({ scope: v as Rule['scope'] })} placeholder="Choose where" search="Search houses…" foot={canOrg(p) ? 'All houses, or one house.' : 'You can add rules for your own houses. Rules for all houses need all-sites authority.'} />
                            {valueOk ? <><InfoCard icon={Check}><b>{ruleSentence(r)}</b></InfoCard><RulePreview r={r} /></> : <InfoCard icon={Users}>Choose {r.match === 'name' ? 'a medicine' : r.match === 'route' ? 'a route' : r.match === 'nzulm' ? 'a product' : 'a type or class'} to see who this rule affects.</InfoCard>}
                        </div>
                    ) : step === 1 ? (
                        <div className="space-y-4">
                            <StepHead icon={ClipboardCheck} title="What it requires" blurb="Before the dose is saved. Observation values and ranges aren’t set here." />
                            <div className="divide-y divide-border rounded-xl border" role="group" aria-label="Before the dose is saved" aria-invalid={err === 'needs' || undefined}>
                                {([['cs', 'A second person confirms with their witness PIN'], ['pulse', 'Record pulse'], ['bsl', 'Record blood sugar (BSL)'], ['bp', 'Record blood pressure']] as const).map(([k, l]) => {
                                    const on = k === 'cs' ? r.countersign : r.obs.includes(k);
                                    return <div key={k} className="flex items-center justify-between gap-4 p-3"><label htmlFor={`rw-n-${k}`} className="text-[13px]">{l}</label><SwitchWord id={`rw-n-${k}`} on={on} onChange={(v) => up(k === 'cs' ? { countersign: v } : { obs: v ? [...r.obs, k] : r.obs.filter((o) => o !== k) })} /></div>;
                                })}
                            </div>
                            {err === 'needs' ? <p className="flex items-center gap-1 text-xs text-status-critical" role="alert"><AlertTriangle className="size-3" />Turn on at least one: a second person or an observation.</p> : null}
                            <div className="flex items-center justify-between gap-4 rounded-xl border p-3"><div><label htmlFor="rw-active" className="text-[13px] font-semibold">Active</label><p className="text-caption">{r.active ? 'Applies from the next dose saved.' : 'Saved, but doesn’t apply until someone turns it on.'}</p></div><SwitchWord id="rw-active" on={r.active} onChange={(v) => up({ active: v })} /></div>
                            <InfoCard icon={Check}><b>{ruleSentence(r)}</b></InfoCard>
                        </div>
                    ) : (
                        <div className="space-y-4">
                            <StepHead icon={Check} title="Review & save" blurb="Check the rule and who it affects." />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <ReviewCard icon={Pill} title="Applies to" onEdit={() => setStep(0)}><ReviewRow label="Medicines" value={ruleWhat(r)} /><ReviewRow label="Where" value={HOUSES[r.scope]} /></ReviewCard>
                                <ReviewCard icon={ClipboardCheck} title="Requires" onEdit={() => setStep(1)}><ReviewRow label="Before a dose is saved" value={ruleNeeds(r)} /><ReviewRow label="Status" value={r.active ? 'Active' : 'Paused'} /></ReviewCard>
                            </div>
                            <RulePreview r={r} />
                            {overlaps.length ? <InfoCard icon={Layers} tone="warn"><b>Overlaps with {overlaps.length === 1 ? 'another rule' : `${overlaps.length} other rules`}.</b> {overlaps.map((o) => ruleSentence(o)).join(' ')} Both apply where they overlap: the dose needs everything either rule asks for.</InfoCard> : null}
                            <Recorded />
                        </div>
                    )}
                </WizardStepPane>
            </WizardShell>
            <DiscardDraftDialog open={guard} mode={src ? 'edit' : 'create'} description="Nothing you’ve entered here has been saved. Closing now loses it." onKeepEditing={() => setGuard(false)} onDiscard={() => { setGuard(false); close(); }} />
        </>
    );
}
/** Switch with its On/Off word (Fleet _notifications.tsx). */
const SwitchWord = ({ id, on, onChange, disabled }: { id: string; on: boolean; onChange: (v: boolean) => void; disabled?: boolean }) => <OnOff id={id} checked={on} onChange={onChange} disabled={disabled} />;

export function RuleView({ id }: { id: string }) {
    const { m } = useStore();
    const { close } = useNav();
    const r = m.rules.find((x) => x.id === id);
    if (!r) return <NotFound what="rule" />;
    return (
        <Modal title="Medicine rule" description={ruleSentence(r)} onClose={close}>
            <ReviewCard icon={Pill} title="Rule"><ReviewRow label="Medicines" value={ruleWhat(r)} /><ReviewRow label="Where" value={HOUSES[r.scope]} /><ReviewRow label="Requires" value={ruleNeeds(r)} /><ReviewRow label="Status" value={r.active ? 'Active' : r.paused || 'Paused'} /><ReviewRow label="Last changed" value={`${r.by} · ${r.when}`} /></ReviewCard>
            <RulePreview r={r} />
            <Notice><span>{r.scope === 'all' ? 'Rules for all houses need all-sites authority — for example Hana Kereama, clinical lead.' : `House rules can be changed by the managers of ${HOUSES[r.scope]}.`}</span></Notice>
        </Modal>
    );
}
export function RuleToggle({ id }: { id: string }) {
    const { m, set, flash } = useStore();
    const { close } = useNav();
    const r = m.rules.find((x) => x.id === id);
    if (!r) return <NotFound what="rule" />;
    return (
        <ConfirmDialog open onClose={close} variant={r.active ? 'destructive' : 'default'} title={r.active ? 'Pause this rule?' : 'Turn this rule back on?'} confirmText={r.active ? 'Pause rule' : 'Turn rule on'}
            description={<><p>{r.active ? 'From the next dose saved, doses no longer need it.' : 'From the next dose saved, doses need it again.'}</p><p className="mt-2 font-medium text-foreground">{ruleSentence(r)}</p><p className="mt-2">Recorded in the change history with your name and the time.</p></>}
            onConfirm={() => { set((d) => { const x = d.rules.find((y) => y.id === id)!; x.active = !x.active; x.by = stamp(d).split(',')[0]; x.when = '29 Sep 2026'; x.paused = x.active ? '' : `Paused 29 Sep 2026 by ${x.by}`; logChange(d, { area: 'rules', sec: 'medicines', scope: HOUSES[x.scope], what: x.active ? 'Medicine rule turned back on' : 'Medicine rule paused', from: x.active ? 'Paused' : 'Active', to: ruleSentence(x), ev: 'medicationadminrule.update' }); }); flash(r.active ? 'Rule paused. Doses no longer need it.' : 'Rule turned back on. It applies from the next dose saved.'); }} />
    );
}
export function RuleHistory({ id }: { id: string }) {
    const { m } = useStore();
    const { close } = useNav();
    const r = m.rules.find((x) => x.id === id);
    if (!r) return <NotFound what="rule" />;
    const key = r.match === 'nzulm' ? 'digoxin' : String(r.value).toLowerCase();
    const rows = allHistory(m).filter((h) => h.area === 'rules' && h.sec === 'medicines' && (h.to.toLowerCase().includes(key) || h.from.toLowerCase().includes(key)));
    return (
        <Modal title="Rule change history" description={ruleSentence(r)} onClose={close}>
            {rows.length ? <ReviewCard icon={History} title="Changes">{rows.map((h) => <ReviewRow key={h.id} label={h.when} value={`${h.who} · ${h.what}`} />)}</ReviewCard> : <p className="text-subtle">No changes recorded for this rule yet.</p>}
        </Modal>
    );
}

/* ── Round templates: WizardShell (When · Who · Review & save) with the approved clock ── */
const TPL_STEPS = [{ key: 'when', label: 'When', blurb: 'Time, window and days', icon: Clock }, { key: 'who', label: 'Who', blurb: 'Staff for the round', icon: Users }, { key: 'review', label: 'Review & save', blurb: 'Check the day', icon: Check }] as const;
function DayTimeline({ t }: { t: Pick<Tpl, 'id' | 'house' | 'time' | 'win' | 'name'> }) {
    const { m } = useStore();
    const start = 6 * 60, span = 17 * 60, pos = (x: number) => Math.max(0, Math.min(100, ((x - start) / span) * 100));
    const blocks = [...m.templates.filter((o) => o.id !== t.id && o.house === t.house && o.status === 'active').map((o) => ({ o, mine: false })), { o: t as Tpl, mine: true }];
    return (
        <div className="rounded-xl border p-3">
            <p className="text-caption mb-2">Rounds at {HOUSES[t.house]} across the day</p>
            <div className="relative h-8 rounded-md bg-muted" role="img" aria-label={`Rounds at ${HOUSES[t.house]}: ${blocks.map(({ o }) => `${o.name || 'this round'} ${fmtT(o.time)}`).join(', ')}`}>
                {blocks.map(({ o, mine }, i) => { const w = Number(o.win) || 0, l = pos(toMin(o.time) - w), rgt = pos(toMin(o.time) + w); return <span key={i} className={mine ? 'absolute top-1 bottom-1 rounded bg-primary text-[10px] font-semibold text-primary-foreground' : 'absolute top-1 bottom-1 rounded bg-primary/25 text-[10px] text-foreground'} style={{ left: `${l}%`, width: `${Math.max(1.5, rgt - l)}%` }}>{rgt - l > 7 ? <span className="px-1">{fmtT(o.time)}</span> : null}</span>; })}
            </div>
            <div className="text-caption relative mt-1 h-4" aria-hidden="true">{[[6, '6 am'], [12, '12 pm'], [18, '6 pm'], [23, '11 pm']].map(([h, l]) => <span key={l} className="absolute -translate-x-1/2 first:translate-x-0 last:-translate-x-full" style={{ left: `${pos(Number(h) * 60)}%` }}>{l}</span>)}</div>
        </div>
    );
}
export function TemplateWizard({ id, seed }: { id: string; seed?: Record<string, string> }) {
    const { m, set } = useStore();
    const { close, open } = useNav();
    const p = m.persona, src = m.templates.find((t) => t.id === id);
    const houses = myHouses(p).filter((h) => canTemplates(h, p));
    const [t, setT] = useState<Tpl>(() => (src ? clone(src) : { id: '', name: '', house: houses[0] ?? 'kowhai', time: '08:00', win: 60, days: [], who: null, status: 'active', by: '', when: '', doses: 3, people: 2 }));
    const [win, setWin] = useState(seed?.twerr === '1' ? '200' : String(src ? src.win : 60));
    const [mode, setMode] = useState<'all' | 'one'>(src?.who ? 'one' : 'all'), [chosenDays, setChosenDays] = useState(!!src?.days.length);
    const [step, setStep] = useState(0), [errs, setErrs] = useState<Record<string, string>>(seed?.twerr === '1' ? { name: 'Give the round a name.', win: 'Enter a whole number of minutes from 5 to 120.' } : {}), [dirty, setDirty] = useState(seed?.twerr === '1'), [saved, setSaved] = useState(false), [guard, setGuard] = useState(false);
    if (id !== 'new' && (!src || !myHouses(p).includes(src.house))) return <NotFound what="template" />;
    const up = (patch: Partial<Tpl>) => { setT({ ...t, ...patch }); setDirty(true); };
    const whoId = STAFF.find((s) => s.name === t.who)?.id ?? '';
    const validate = (only?: number) => {
        const e: Record<string, string> = {};
        if (only == null || only === 0) { if (!t.name.trim()) e.name = 'Give the round a name.'; const w = Number(win); if (!Number.isInteger(w) || w < 5 || w > 120) e.win = 'Enter a whole number of minutes from 5 to 120.'; if (!t.time) e.time = 'Choose the round time.'; }
        if ((only == null || only === 1) && mode === 'one' && !t.who) e.who = 'Choose the default staff member, or choose everyone rostered.';
        return e;
    };
    const next = () => { const e = validate(step); setErrs(e); if (!Object.keys(e).length) setStep(Math.min(2, step + 1)); };
    const tt = { ...t, win: Number(win) || 0 };
    const save = () => {
        const e = validate(); setErrs(e);
        if (Object.keys(e).length) { setStep(e.name || e.win || e.time ? 0 : 1); return; }
        const data: Tpl = { ...tt, name: t.name.trim(), who: mode === 'one' ? t.who : null, days: chosenDays ? t.days : [] };
        const txt = (o: Tpl) => `${fmtT(o.time)} ±${o.win} · ${daysText(o.days)} · ${o.who || 'everyone rostered'} · ${o.status}`;
        set((d) => {
            const who = stamp(d).split(',')[0];
            if (src) { const o = d.templates.find((x) => x.id === src.id)!; const from = txt(o); Object.assign(o, data, { by: who, when: '29 Sep 2026' }); logChange(d, { area: 'rounds', sec: 'templates', scope: HOUSES[o.house], what: `Round template changed — ${o.name}`, from, to: txt(o), ev: 'medications.round_template.updated (audit needed — not recorded today)' }); }
            else { d.templates.push({ ...data, id: `t${d.templates.length + 1}`, by: who, when: '29 Sep 2026' }); logChange(d, { area: 'rounds', sec: 'templates', scope: HOUSES[data.house], what: `Round template added — ${data.name}`, from: '—', to: txt(data), ev: 'medications.round_template.created (audit needed — not recorded today)' }); }
        });
        setSaved(true); setDirty(false);
    };
    const onClose = () => (dirty && !saved ? setGuard(true) : close());
    const staffItems: PickItem[] = STAFF.map((x) => staffNow(m, x)).filter((x) => x.house === t.house).map((x) => { const ok = givenAbility(m, x).v === 'yes'; return { id: x.id, name: x.name, sub: `${x.role}`, ok, why: ok ? undefined : 'can’t record given doses' }; });
    const ov = tplOverlaps(m, tt);
    const pct = Math.round(([t.name.trim(), t.time, win, mode === 'all' || t.who, true].filter(Boolean).length / 5) * 100);
    const sentence = `${t.name || 'This round'} at ${HOUSES[t.house]}: ${fmtT(t.time)}, doses due ${win || '…'} minutes either side · ${daysText(chosenDays ? t.days : []).toLowerCase()} · ${mode === 'one' && t.who ? `default staff ${t.who}` : 'everyone rostered on a covering shift'}.`;
    return (
        <>
            <WizardShell open onClose={onClose} title={src ? 'Edit round template' : 'Add a round template'} description="When a medication round happens at a house." railIcon={Repeat} railTitle={src ? 'Edit round template' : 'Add a round template'} railSub="Settings › Rounds & timing"
                steps={TPL_STEPS} stepIndex={step} onStepClick={(i) => setStep(i)} pct={pct}
                success={saved ? <WizardSuccessPane title={src ? 'Template updated' : 'Template added'} blurb={<>{sentence}<br />{t.status === 'active' ? 'Rounds are created from it from tomorrow (12:05 am). Today’s rounds keep their times.' : 'Saved as paused — no rounds are created until someone turns it on.'}</>} actions={<><Button variant="outline" onClick={() => open({ kind: 'gen' })}>Create rounds for a day</Button><Button onClick={close} autoFocus>Done</Button></>} /> : undefined}
                footerStart={<div className="flex gap-2"><Button variant="outline" onClick={onClose}>Cancel</Button>{step > 0 ? <Button variant="outline" onClick={() => setStep(step - 1)}><ChevronLeft />Back</Button> : null}</div>}
                footerEnd={step < 2 ? <Button onClick={next}>Continue<ChevronRight /></Button> : <Button onClick={save}><Check />{src ? 'Save changes' : 'Save template'}</Button>}>
                <WizardStepPane key={step}>
                    {step === 0 ? (
                        <div className="space-y-5">
                            <StepHead icon={Clock} title="When" blurb="The round time, how long doses count as due, and which days." />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Name" required error={errs.name}><Input id="tw-name" value={t.name} placeholder="For example: Morning round" aria-invalid={!!errs.name || undefined} onChange={(e) => { up({ name: e.target.value }); setErrs({ ...errs, name: '' }); }} /></Field>
                                {src ? <Field label="House"><InfoCard icon={Home}><b>{HOUSES[t.house]}</b> — a template stays with its house.</InfoCard></Field>
                                    : <RecordPicker id="tw-house" label="House" required value={t.house} items={houses.map((h) => ({ id: h, name: HOUSES[h], sub: `House lead: ${h === 'kowhai' ? 'Jordan Tipene' : 'Sione Taufa'}`, ok: true }))} onChange={(v) => up({ house: v as HouseKey, who: null })} placeholder="Choose a house" search="Search houses…" />}
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Round time" required hint="Pacific/Auckland" htmlFor="tw-time" error={errs.time}><TimePicker id="tw-time" label="Round time" value={t.time} invalid={!!errs.time} onChange={(v) => up({ time: v })} /></Field>
                                <Field label="Doses due within" required htmlFor="tw-win" errorId="tw-win-error" error={errs.win}>
                                    <div><NumberInput id="tw-win" value={win} unit="minutes either side" min={5} max={120} error={errs.win} onChange={(v) => { setWin(v); setDirty(true); setErrs({ ...errs, win: '' }); }} />{errs.win ? null : <p className="text-caption mt-1">5 to 120 minutes either side (today’s limit).</p>}</div>
                                </Field>
                            </div>
                            <Field label="Days" required>
                                <div className="space-y-2">
                                    <div className="flex items-center justify-between gap-4 rounded-xl border p-3"><label htmlFor="tw-every" className="text-[13px] font-semibold">Every day</label><OnOff id="tw-every" checked={!chosenDays} onChange={(v) => { setChosenDays(!v); setDirty(true); }} /></div>
                                    {chosenDays ? <ChipMulti values={t.days.map((k) => DAYS.find((d) => d[0] === k)![1])} options={DAYS.map((d) => d[1])} onChange={(v) => up({ days: DAYS.filter((d) => v.includes(d[1])).map((d) => d[0]) })} /> : null}
                                </div>
                            </Field>
                            <InfoCard icon={Check}><b>{sentence}</b></InfoCard>
                            <DayTimeline t={tt} />
                            {ov.length ? <InfoCard icon={Layers} tone="warn"><b>Overlaps the {ov.map((o) => `${o.name} (${fmtT(o.time)})`).join(' and ')}.</b> A dose due in both windows shows in the earlier round. You can still save.</InfoCard> : null}
                        </div>
                    ) : step === 1 ? (
                        <div className="space-y-5">
                            <StepHead icon={Users} title="Who does this round" blurb="Everyone rostered on a covering shift, unless you choose one person." />
                            <TilePicker value={mode} onChange={(v) => { setMode(v as 'all' | 'one'); if (v === 'all') up({ who: null }); setDirty(true); }} options={[{ key: 'all', label: 'Everyone rostered on a covering shift', description: 'A lead can narrow a round to one person on the day.', icon: Users }, { key: 'one', label: 'One person by default', description: 'They get the round each day it’s created. Anyone rostered can still take over.', icon: User }]} />
                            {mode === 'one' ? <RecordPicker id="tw-person" label="Default staff" required value={whoId} items={staffItems} error={errs.who} onChange={(v) => { up({ who: STAFF.find((s) => s.id === v)!.name }); setErrs({ ...errs, who: '' }); }} foot={`People at ${HOUSES[t.house]} who can record given doses. Others are listed but can’t be chosen.`} /> : null}
                        </div>
                    ) : (
                        <div className="space-y-4">
                            <StepHead icon={Check} title="Review & save" blurb="Check the round and the day." />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <ReviewCard icon={Clock} title="When" onEdit={() => setStep(0)}><ReviewRow label="Name" value={t.name} /><ReviewRow label="House" value={HOUSES[t.house]} /><ReviewRow label="Round time" value={`${fmtT(t.time)}, ${win} minutes either side`} /><ReviewRow label="Days" value={daysText(chosenDays ? t.days : [])} /></ReviewCard>
                                <ReviewCard icon={Users} title="Who" onEdit={() => setStep(1)}><ReviewRow label="Staff" value={mode === 'one' && t.who ? t.who : 'Everyone rostered on a covering shift'} /></ReviewCard>
                            </div>
                            <DayTimeline t={tt} />
                            {ov.length ? <InfoCard icon={Layers} tone="warn"><b>Overlaps the {ov.map((o) => `${o.name} (${fmtT(o.time)})`).join(' and ')}.</b> A dose due in both windows shows in the earlier round.</InfoCard> : null}
                            <div className="flex items-center justify-between gap-4 rounded-xl border p-3"><div><label htmlFor="tw-active" className="text-[13px] font-semibold">Create rounds from this template</label><p className="text-caption">{t.status === 'active' ? 'Rounds are created from tomorrow (12:05 am). Today’s rounds keep their times.' : 'Saved as paused — no rounds are created yet.'}</p></div><SwitchWord id="tw-active" on={t.status === 'active'} onChange={(v) => up({ status: v ? 'active' : 'paused' })} /></div>
                            <Recorded />
                        </div>
                    )}
                </WizardStepPane>
            </WizardShell>
            <DiscardDraftDialog open={guard} mode={src ? 'edit' : 'create'} description="Nothing you’ve entered here has been saved. Closing now loses it." onKeepEditing={() => setGuard(false)} onDiscard={() => { setGuard(false); close(); }} />
        </>
    );
}
export function TemplateView({ id }: { id: string }) {
    const { m } = useStore();
    const { close } = useNav();
    const t = m.templates.find((x) => x.id === id);
    if (!t || !myHouses(m.persona).includes(t.house)) return <NotFound what="template" />;
    return (
        <Modal title={`${t.name} — ${HOUSES[t.house]}`} description={t.status === 'retired' ? `Retired ${t.when} by ${t.by}. Retired templates can’t be changed or used again.` : 'Only people who manage orders at this house can change it.'} onClose={close}>
            <ReviewCard icon={Clock} title="Round"><ReviewRow label="Round time" value={`${fmtT(t.time)}, ${t.win} minutes either side`} /><ReviewRow label="Days" value={daysText(t.days)} /><ReviewRow label="Staff" value={t.who || 'Everyone rostered on a covering shift'} /><ReviewRow label="Status" value={{ active: 'Active', paused: 'Paused', retired: 'Retired' }[t.status]} /><ReviewRow label="Last changed" value={`${t.by} · ${t.when}`} /></ReviewCard>
            <DayTimeline t={t} />
        </Modal>
    );
}
export function TemplateToggle({ id, retire }: { id: string; retire?: boolean }) {
    const { m, set, flash } = useStore();
    const { close } = useNav();
    const t = m.templates.find((x) => x.id === id);
    if (!t) return <NotFound what="template" />;
    const a = retire ? 'retire' : t.status === 'active' ? 'pause' : 'resume';
    const x = { pause: ['Stop creating rounds from this template?', `From tomorrow no ${t.name} is created at ${HOUSES[t.house]}. Doses still show on Meds today.`, 'Pause template', 'destructive'], resume: ['Create rounds from this template again?', `From tomorrow a ${t.name} is created at ${HOUSES[t.house]} ${daysText(t.days).toLowerCase()}.`, 'Turn template on', 'default'], retire: ['Retire this template?', 'No new rounds are created from it. Past rounds keep it on their record. A retired template can’t be changed or used again.', 'Retire template', 'destructive'] }[a] as [string, string, string, 'destructive' | 'default'];
    return (
        <ConfirmDialog open onClose={close} title={x[0]} confirmText={x[2]} variant={x[3]} description={<><p>{x[1]}</p><p className="mt-2 font-medium text-foreground">{t.name} · {HOUSES[t.house]} · {fmtT(t.time)}, {t.win} minutes either side · {daysText(t.days)}</p><p className="mt-2">Recorded in the change history with your name and the time.</p></>}
            onConfirm={() => { set((d) => { const o = d.templates.find((y) => y.id === id)!; const from = o.status; o.status = a === 'retire' ? 'retired' : a === 'pause' ? 'paused' : 'active'; o.by = stamp(d).split(',')[0]; o.when = '29 Sep 2026'; logChange(d, { area: 'rounds', sec: 'templates', scope: HOUSES[o.house], what: `Round template ${a === 'retire' ? 'retired' : a === 'pause' ? 'paused' : 'turned back on'} — ${o.name}`, from: from[0].toUpperCase() + from.slice(1), to: o.status[0].toUpperCase() + o.status.slice(1), ev: a === 'retire' ? 'medications.round_template.retired' : 'medications.round_template.updated (audit needed — not recorded today)' }); }); flash(a === 'retire' ? 'Template retired. No new rounds are created from it.' : a === 'pause' ? 'Template paused. No rounds from tomorrow.' : 'Template turned back on. Rounds are created from tomorrow.'); }} />
    );
}

/* ── Create rounds for a day (GenerateRoundsModal) ── */
export function CreateRounds() {
    const { m, flash } = useStore();
    const { close } = useNav();
    const houses = myHouses(m.persona).filter((h) => canTemplates(h, m.persona));
    const [house, setHouse] = useState<HouseKey>(houses[0] ?? 'kowhai'), [date, setDate] = useState('2026-09-29'), [all, setAll] = useState('0');
    const [y, mo, dd] = date.split('-').map(Number), g = new Date(y, mo - 1, dd).getDay(), dow = String(g === 0 ? 7 : g);
    const list = m.templates.filter((t) => t.house === house && t.status === 'active' && (all === '1' || !t.days.length || t.days.includes(dow)));
    const exists = date === '2026-09-29' ? list : [], create = list.filter((t) => !exists.includes(t));
    return (
        <Modal title="Create rounds for a day" description="Rounds are created automatically at 12:05 am each day. Use this to create them sooner — for example after adding a template." onClose={close}
            footer={<><Button variant="outline" onClick={close}>Cancel</Button><Button disabled={!create.length} onClick={() => { close(); flash(`${create.length} ${create.length === 1 ? 'round' : 'rounds'} created at ${HOUSES[house]} (${exists.length} already existed and were skipped).`); }}>Create rounds</Button></>}>
            <RecordPicker id="gw-house" label="House" value={house} items={houses.map((h) => ({ id: h, name: HOUSES[h], sub: `${m.templates.filter((t) => t.house === h && t.status === 'active').length} active templates`, ok: true }))} onChange={(v) => setHouse(v as HouseKey)} placeholder="Choose a house" search="Search houses…" />
            <Field label="Day" hint="Pacific/Auckland"><DatePicker id="gw-date" label="Day" value={date} onChange={setDate} /></Field>
            <TilePicker value={all} onChange={setAll} options={[{ key: '0', label: 'Only those set for that day', description: 'Follows each template’s days', icon: CalendarDays }, { key: '1', label: 'All active templates', description: 'Ignores the days — for a one-off change', icon: Repeat }]} />
            <ReviewCard icon={Repeat} title={create.length ? `Creates ${create.length} ${create.length === 1 ? 'round' : 'rounds'}` : 'Nothing new to create'}>
                {list.length ? list.map((t) => <ReviewRow key={t.id} label={`${t.name} · ${fmtT(t.time)}`} value={exists.includes(t) ? 'Already exists — skipped' : <b>New</b>} />) : <p className="text-caption">No active templates apply that day.</p>}
            </ReviewCard>
            <p className="text-caption">Rounds that already exist are skipped — creating twice never makes duplicates.</p>
        </Modal>
    );
}

/* ── On-call contact. Stephan, 29 Sep 2026: the contact is an employed staff member and it follows the roster —
 * whoever is on an on-call shift at the house (Rostering: shifts.is_on_call / shift_type on_call), then, if chosen,
 * the team lead on shift, then a backup person. Or always the same person. Phone numbers come from staff records. ── */
function SwitchRow({ id, label, hint, checked, onChange, disabled }: { id: string; label: string; hint: string; checked: boolean; onChange: (v: boolean) => void; disabled?: boolean }) {
    return (
        <div className="flex items-center justify-between gap-4 p-3">
            <div><label htmlFor={id} className="text-[13px] font-semibold">{label}</label><p className="text-caption">{hint}</p></div>
            <OnOff id={id} checked={checked} disabled={disabled} onChange={onChange} />
        </div>
    );
}
function RosterPreview({ h, r }: { h: HouseKey; r: OnCallRule }) {
    return (
        <ReviewCard icon={CalendarDays} title="Who staff will see after hours">
            {ONCALL_ROSTER[h].map((n) => {
                const x = resolveOnCall(r, n);
                return <ReviewRow key={n.day} label={`${n.day} · ${n.hours}`} value={x.who ? <span className="text-right"><b>{x.who.name}</b> · {x.who.phone}<span className="text-caption block">{x.how}</span></span> : <span className="text-status-critical">{r.mode === 'roster' ? 'Nobody rostered — choose a backup' : 'Choose the on-call person'}</span>} />;
            })}
        </ReviewCard>
    );
}
export function OnCallDialog({ h, seed }: { h: HouseKey; seed?: Record<string, string> }) {
    const { m, set, flash } = useStore();
    const { close } = useNav();
    const c = m.oncall[h];
    const bad = seed?.owerr === '1';
    useEffect(() => { if (bad) { const t = setTimeout(() => document.getElementById('ow-person')?.focus(), 60); return () => clearTimeout(t); } }, [bad]);
    const [r, setR] = useState<OnCallRule>(() => ({ mode: c?.mode ?? 'roster', teamLead: c?.teamLead ?? true, person: bad ? '' : c?.person ?? '' }));
    const [err, setErr] = useState(bad ? 'Choose who staff call when nobody is rostered on call.' : ''), [fail, setFail] = useState(false);
    if (!canHouse(h, m.persona)) return <OnCallView h={h} />;
    const roster = r.mode === 'roster', offline = m.demo.settings === 'offline', p = employee(r.person);
    const items: PickItem[] = employedAt(h).map((x) => ({ id: x.id, name: x.name, sub: x.phone ? `${x.role} · ${x.phone}` : x.role, ok: !!x.phone, why: 'no phone number on their staff record' }));
    const save = () => {
        if (!p) { setErr(roster ? 'Choose who staff call when nobody is rostered on call.' : 'Choose the on-call person.'); setTimeout(() => document.getElementById('ow-person')?.focus(), 0); return; }
        if (m.demo.save === 'fail') { setFail(true); set((d) => { d.demo.save = 'ok'; }); return; }
        set((d) => { const was = d.oncall[h]; d.oncall[h] = { ...r, by: stamp(d).split(',')[0], when: '29 Sep 2026' }; logChange(d, { area: 'alerts', sec: 'oncall', scope: HOUSES[h], what: 'On-call contact', from: was ? describeOnCall(was) : 'Not configured', to: describeOnCall(r), ev: 'medications.oncall_contact.updated (new)' }); });
        close(); flash(`On-call contact saved for ${HOUSES[h]}.`);
    };
    return (
        <Modal title={`On-call contact — ${HOUSES[h]}`} description="Who staff at this house call when they need help. Shown on blocked screens, escalations and follow-ups." onClose={close}
            footer={<><Button variant="outline" onClick={close}>Cancel</Button><Button onClick={save} disabled={offline}>{fail ? 'Try again' : 'Save contact'}</Button></>}>
            {fail ? <Notice><span><b>Couldn’t save — nothing was changed.</b> Check your connection and try again. Your choices are kept.</span></Notice> : null}
            {offline ? <Notice><span>You’re offline — reconnect to save.</span></Notice> : null}
            <div className="divide-y divide-border rounded-xl border">
                <SwitchRow id="ow-roster" label="Follow the roster" hint={`Whoever is on an on-call shift at ${HOUSES[h]}. On-call shifts are set in Rostering.`} checked={roster} onChange={(v) => setR({ ...r, mode: v ? 'roster' : 'fixed' })} />
                {roster ? <SwitchRow id="ow-lead" label="Then the team lead on shift" hint="If nobody is on an on-call shift, the team lead working at the house." checked={r.teamLead} onChange={(v) => setR({ ...r, teamLead: v })} /> : null}
            </div>
            <RecordPicker id="ow-person" label={roster ? 'If nobody is rostered' : 'On-call person'} required value={r.person} items={items} error={err} onChange={(v) => { setR({ ...r, person: v }); setErr(''); }} placeholder="Search and choose a staff member" search="Search staff…" foot={`Employed staff with access to ${HOUSES[h]}. Their phone number comes from their staff record.`} />
            {p ? <InfoCard icon={Phone}><b>{p.name}</b> · {p.phone} — from their staff record.</InfoCard> : null}
            <RosterPreview h={h} r={r} />
            <p className="text-caption">Recorded in the change history. Other houses are unchanged.</p>
        </Modal>
    );
}
export function OnCallView({ h }: { h: HouseKey }) {
    const { m } = useStore();
    const { close } = useNav();
    const c = m.oncall[h], b = c ? employee(c.person) : undefined;
    return (
        <Modal title={`On-call contact — ${HOUSES[h]}`} description="Only someone who manages settings for this house can change it." onClose={close}>
            {c ? (
                <>
                    <ReviewCard icon={Phone} title="How it’s decided">
                        <ReviewRow label="Rule" value={c.mode === 'roster' ? `Follows the roster${c.teamLead ? ', then the team lead on shift' : ''}` : 'Always the same person'} />
                        <ReviewRow label={c.mode === 'roster' ? 'Backup' : 'Person'} value={b ? `${b.name} · ${b.phone}` : '—'} />
                        <ReviewRow label="Last changed" value={`${c.by} · ${c.when}`} />
                    </ReviewCard>
                    <RosterPreview h={h} r={c} />
                </>
            ) : <ReviewCard icon={Phone} title="On-call contact"><p className="text-[13px]">Not configured — screens at this house give no number.</p></ReviewCard>}
        </Modal>
    );
}
export function OnCallRemove({ h }: { h: HouseKey }) {
    const { m, set, flash } = useStore();
    const { close } = useNav();
    const c = m.oncall[h];
    return (
        <ConfirmDialog open onClose={close} title={`Remove the on-call contact for ${HOUSES[h]}?`} confirmText="Remove contact"
            description={`Screens at ${HOUSES[h]} say “On-call contact: Not configured” again and give no number — even when someone is rostered on call. ${c ? `Now: ${describeOnCall(c)}.` : ''}`}
            onConfirm={() => { set((d) => { const was = d.oncall[h]!; d.oncall[h] = null; logChange(d, { area: 'alerts', sec: 'oncall', scope: HOUSES[h], what: 'On-call contact removed', from: describeOnCall(was), to: 'Not configured', ev: 'medications.oncall_contact.updated (new)' }); }); flash(`On-call contact removed for ${HOUSES[h]}. Screens show “Not configured” again.`); }} />
    );
}

/* ── Time-critical medicine (answer 13/14 dose timing) ── */
export function TimeCritical() {
    const { m, set, flash } = useStore();
    const { close } = useNav();
    const [med, setMed] = useState(''), [min, setMin] = useState(''), [e, setE] = useState<Record<string, string>>({});
    const late = Number(m.draft.timing.late);
    const items: PickItem[] = [...new Set(MEDLIST.filter((x) => has(m.persona, 'cd.view') || !x.cd).map((x) => x.med))].map((n) => { const done = m.draft.timing.critical.some((c) => c.med === n); return { id: n, name: n, sub: MEDLIST.filter((x) => x.med === n).map((x) => x.route)[0], ok: !done, why: done ? 'already marked' : undefined }; });
    const add = () => {
        const x: Record<string, string> = {};
        if (!med) x.med = 'Choose a medicine.';
        if (!/^\d+$/.test(min) || Number(min) < 1 || Number(min) >= late) x.min = `Enter whole minutes, shorter than the general late time (${late} minutes).`;
        setE(x);
        if (Object.keys(x).length) { setTimeout(() => document.querySelector<HTMLElement>('[role="dialog"] [aria-invalid="true"]')?.focus(), 0); return; }
        set((d) => { d.draft.timing.critical.push({ med, min }); });
        close(); flash(`${med} marked as time-critical in your draft. Review and save dose timing to apply it.`);
    };
    return (
        <Modal title="Mark a medicine as time-critical" description={`It gets its own late time, shorter than the general ${late} minutes. Nothing changes until you save the view.`} onClose={close}
            footer={<><Button variant="outline" onClick={close}>Cancel</Button><Button onClick={add}>Add to draft</Button></>}>
            <RecordPicker id="tc-med" label="Medicine" required value={med} items={items} onChange={(v) => { setMed(v); setE({ ...e, med: '' }); }} error={e.med} placeholder="Search and choose a medicine" search="Search medicines…" />
            <Field label="Counts as late after" required error={e.min}><NumberInput id="tc-min" value={min} unit="minutes after the dose time" error={e.min} onChange={(v) => { setMin(v); setE({ ...e, min: '' }); }} /></Field>
        </Modal>
    );
}

/* ── Reset a witness PIN (P00 wording; house leads for their houses — answer 20) ── */
export function PinReset({ name }: { name: string }) {
    const { set, flash } = useStore();
    const { close } = useNav();
    return (
        <ConfirmDialog open onClose={close} title={`Reset ${name}’s witness PIN?`} confirmText="Reset PIN"
            description={<><p>They won’t be able to co-sign or witness until they choose a new PIN from their own account.</p><p className="mt-2">Nobody sees the old or the new PIN. {name} gets a message asking them to set a new one in Settings › Witness PIN. The reset is recorded in the audit log.</p></>}
            onConfirm={() => { set((d) => { const x = d.pins.find((y) => y.name === name); if (x) { x.pin = 'adminreset'; x.changed = undefined; } }); flash(`${name}’s PIN was reset. They must set a new one before they can co-sign or witness.`); }} />
    );
}

/* ── Add a named person to an alert (every house, or one house’s extras). Adds to the draft; applies on save. ── */
export function AlertPerson({ k, scope }: { k: string; scope: string }) {
    const { m, set, flash } = useStore();
    const { close } = useNav();
    const a = ALERTS.find((x) => x.k === k);
    const [who, setWho] = useState(''), [err, setErr] = useState('');
    if (!a) return <NotFound what="alert" />;
    const house = scope === 'org' ? null : (scope as HouseKey);
    if (house ? !canHouse(house, m.persona) : !canOrg(m.persona)) return <NotFound what="alert" />;
    const taken = house ? m.draft.alertExtra[`${house}.${k}`] ?? [] : m.draft.alerts[k].people;
    const add = () => {
        if (!who) { setErr('Choose a person.'); setTimeout(() => document.getElementById('ap-who')?.focus(), 0); return; }
        set((d) => { if (house) d.draft.alertExtra[`${house}.${k}`] = [...(d.draft.alertExtra[`${house}.${k}`] ?? []), who]; else d.draft.alerts[k].people = [...d.draft.alerts[k].people, who]; });
        close(); flash(`${who} added to “${a.l}” in your draft. Review and save to apply it.`);
    };
    return (
        <Modal title={`Add a person to “${a.l}”`} description={house ? `They get it only when the alert is about ${HOUSES[house]}.` : 'They get it for every house they have access to.'} onClose={close}
            footer={<><Button variant="outline" onClick={close}>Cancel</Button><Button onClick={add}>Add to draft</Button></>}>
            <RecordPicker id="ap-who" label="Person" required value={who} items={peopleItems(taken)} error={err} onChange={(v) => { setWho(v); setErr(''); }} placeholder="Search and choose a person" search="Search people…" />
            <p className="text-caption">Named people add to the switched-on groups; they never replace them. Alerts about controlled medicines only reach people with controlled-medicine access. Nothing changes until you review and save.</p>
        </Modal>
    );
}

/* ── Keep today’s value: confirms a default as reviewed without changing it (records who and when). ── */
export function KeepDefault({ spec }: { spec: string }) {
    const { m, set, flash } = useStore();
    const { close } = useNav();
    const [g, k] = spec.split('|') as [keyof typeof GROUPS, string];
    const G = GROUPS[g];
    if (!G || !G.keys.includes(k) || !G.can(m.persona)) return <NotFound what="setting" />;
    const label = g === 'ea' ? 'The emergency access policy' : G.label(k);
    const value = g === 'ea' ? `${G.fmt('def', m.saved.ea.def)} per grant, longest ${G.fmt('max', m.saved.ea.max)}` : G.fmt(k, (m.saved[g] as Record<string, unknown>)[k]);
    return (
        <ConfirmDialog open onClose={close} variant="default" title="Keep today’s value?" confirmText="Keep this value"
            description={<><p><b className="text-foreground">{label}</b>: {value}.</p><p className="mt-2">Nothing changes in how doses are recorded. The setting shows as reviewed by you, and this is recorded in the change history and the audit log.</p>{g === 'ea' ? <p className="mt-2">The emergency access policy is reviewed as a whole.</p> : null}</>}
            onConfirm={() => { set((d) => { if (g === 'ea') d.eaSaved = stamp(d); else { d.setBy[g][k] = stamp(d); if (g === 'timing' && k === 'escalN') d.setBy.timing.escalDays = stamp(d); } logChange(d, { area: G.view as 'rules', sec: G.sec(k), scope: 'All houses', what: `Reviewed — ${label}`, from: 'Default — not yet reviewed', to: `Kept: ${value}`, ev: G.ev }); }); flash(`Kept today’s value for “${label}”. It now shows as reviewed.`); }} />
    );
}

/* ── Who gets an alert: WizardShell (Groups · Named people · House extras · Review). Applies to the page draft;
 * nothing changes until the Alerts tab is reviewed and saved. Groups and named people need all-sites authority;
 * house extras need access to that house. Decided groups stay locked on. ── */
const AWHO_STEPS = [{ key: 'groups', label: 'Groups', blurb: 'Who is told, by role', icon: Users }, { key: 'people', label: 'Named people', blurb: 'At every house', icon: User }, { key: 'extras', label: 'House extras', blurb: 'Only for one house', icon: Home }, { key: 'review', label: 'Review', blurb: 'Apply to your draft', icon: Check }] as const;
function PeopleEditor({ id, list, onChange, disabled, foot }: { id: string; list: string[]; onChange: (l: string[]) => void; disabled: boolean; foot: string }) {
    return (
        <div className="space-y-3">
            {list.length ? <ul className="divide-y divide-border rounded-xl border">{list.map((n) => <li key={n} className="flex items-center justify-between gap-3 p-3 text-[13px]"><span>{n} <span className="text-caption">· {ALERT_PEOPLE.find((y) => y.name === n)?.role}</span></span>{disabled ? null : <Button variant="outline" size="sm" aria-label={`Remove ${n}`} onClick={() => onChange(list.filter((y) => y !== n))}>Remove</Button>}</li>)}</ul> : <p className="text-subtle">Nobody named.</p>}
            {disabled ? null : <RecordPicker id={id} label="Add a person" value="" items={peopleItems(list)} onChange={(v) => onChange([...list, v])} placeholder="Search and choose a person" search="Search people…" foot={foot} />}
        </div>
    );
}
export function AlertWho({ k }: { k: string }) {
    const { m, set, flash } = useStore();
    const { close } = useNav();
    const p = m.persona, a = ALERTS.find((x) => x.k === k);
    const offline = m.demo.settings === 'offline', audit = !has(p, 'settings.manage');
    const canO = canOrg(p) && !audit && !offline;
    const mine = HOUSE_KEYS.filter((h) => myHouses(p).includes(h));
    const canH = (h: HouseKey) => canHouse(h, p) && !audit && !offline;
    const [x, setX] = useState(() => clone(m.draft.alerts[k] ?? { inapp: true, email: false, groups: [] as string[], people: [] as string[] }));
    const [extra, setExtra] = useState<Record<string, string[]>>(() => Object.fromEntries(HOUSE_KEYS.map((h) => [h, [...(m.draft.alertExtra[`${h}.${k}`] ?? [])]])));
    const [house, setHouse] = useState<HouseKey>(mine.find(canH) ?? mine[0] ?? 'kowhai');
    const [step, setStep] = useState(0), [dirty, setDirty] = useState(false), [guard, setGuard] = useState(false);
    if (!a) return <NotFound what="alert" />;
    const editable = canO || mine.some(canH);
    const toggle = (g: string, on: boolean) => { setX({ ...x, groups: on ? [...x.groups, g] : x.groups.filter((y) => y !== g) }); setDirty(true); };
    const apply = () => {
        set((d) => { if (canO) { d.draft.alerts[k].groups = [...x.groups]; d.draft.alerts[k].people = [...x.people]; } mine.filter(canH).forEach((h) => { d.draft.alertExtra[`${h}.${k}`] = [...extra[h]]; }); });
        close(); flash(`Changes to “${a.l}” are in your draft. Review and save the Alerts tab to apply them.`);
    };
    const onClose = () => (dirty ? setGuard(true) : close());
    const names = (l: string[]) => l.join(', ') || 'Nobody';
    return (
        <>
            <WizardShell open onClose={onClose} title={`Who gets “${a.l}”`} description="Choose the groups and people who get this alert." railIcon={Bell} railTitle={a.l} railSub={`In-app ${x.inapp ? 'on' : 'off'} · email ${x.email ? 'on' : 'off'}`}
                steps={AWHO_STEPS} stepIndex={step} onStepClick={setStep}
                footerStart={<div className="flex gap-2"><Button variant="outline" onClick={onClose}>{editable ? 'Cancel' : 'Close'}</Button>{step > 0 ? <Button variant="outline" onClick={() => setStep(step - 1)}><ChevronLeft />Back</Button> : null}</div>}
                footerEnd={step < 3 ? <Button onClick={() => setStep(step + 1)}>Continue<ChevronRight /></Button> : editable ? <Button onClick={apply}><Check />Apply to draft</Button> : null}>
                <WizardStepPane key={step}>
                    {step === 0 ? (
                        <div className="space-y-4">
                            <StepHead icon={Users} title="Groups" blurb={a.sub ?? 'Who is told when this happens.'} />
                            {!canO && !audit ? <InfoCard icon={LockKeyhole}>Only someone with all-sites authority changes the groups. You can add extra people for your own houses.</InfoCard> : null}
                            <div className="divide-y divide-border rounded-xl border">
                                {a.groups.map((g) => {
                                    const locked = a.locked?.includes(g), on = locked || x.groups.includes(g);
                                    return (
                                        <div key={g} className="flex items-center justify-between gap-4 p-3">
                                            <div><label htmlFor={`aw-${g}`} className="flex items-center gap-1.5 text-[13px] font-medium">{locked ? <LockKeyhole className="size-3.5" aria-hidden="true" /> : null}{RECIPIENT_GROUPS[g].l}</label><p className="text-caption">{locked ? 'Always on' : g === 'onCall' && HOUSE_KEYS.some((h) => !m.oncall[h]) ? `${RECIPIENT_GROUPS[g].d}. ${HOUSE_KEYS.filter((h) => !m.oncall[h]).map((h) => HOUSES[h]).join(' and ')} ${HOUSE_KEYS.filter((h) => !m.oncall[h]).length > 1 ? 'have' : 'has'} no on-call contact yet.` : RECIPIENT_GROUPS[g].d}</p></div>
                                            <OnOff id={`aw-${g}`} checked={!!on} disabled={!!locked || !canO} onChange={(v) => toggle(g, v)} />
                                        </div>
                                    );
                                })}
                            </div>
                            <p className="text-caption">How it’s sent (in-app, email) is set in the Alerts table. Controlled-medicine alerts only reach people with controlled-medicine access.</p>
                        </div>
                    ) : step === 1 ? (
                        <div className="space-y-4">
                            <StepHead icon={User} title="Named people" blurb="They get this alert for every house they have access to, as well as the groups." />
                            <PeopleEditor id="aw-people" list={x.people} disabled={!canO} onChange={(l) => { setX({ ...x, people: l }); setDirty(true); }} foot="Named people add to the groups; they never replace them." />
                        </div>
                    ) : step === 2 ? (
                        <div className="space-y-4">
                            <StepHead icon={Home} title="House extras" blurb="Extra people who get this alert only when it’s about one house." />
                            <RecordPicker id="aw-house" label="House" value={house} items={mine.map((h) => ({ id: h, name: HOUSES[h], sub: canH(h) ? `${extra[h].length} extra` : 'Read-only for you', ok: true }))} onChange={(v) => setHouse(v as HouseKey)} placeholder="Choose a house" search="Search houses…" />
                            <PeopleEditor id="aw-extra" list={extra[house]} disabled={!canH(house)} onChange={(l) => { setExtra({ ...extra, [house]: l }); setDirty(true); }} foot={`Only for alerts about ${HOUSES[house]}.`} />
                        </div>
                    ) : (
                        <div className="space-y-4">
                            <StepHead icon={Check} title="Review" blurb="These go into your draft. Nothing changes until you review and save the Alerts tab." />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <ReviewCard icon={Users} title="Groups" onEdit={() => setStep(0)}><ReviewRow label="Told" value={names(a.groups.filter((g) => a.locked?.includes(g) || x.groups.includes(g)).map((g) => RECIPIENT_GROUPS[g].l))} /></ReviewCard>
                                <ReviewCard icon={User} title="Named people" onEdit={() => setStep(1)}><ReviewRow label="Every house" value={names(x.people)} /></ReviewCard>
                                <ReviewCard icon={Home} title="House extras" onEdit={() => setStep(2)} span>{mine.map((h) => <ReviewRow key={h} label={HOUSES[h]} value={names(extra[h])} />)}</ReviewCard>
                            </div>
                        </div>
                    )}
                </WizardStepPane>
            </WizardShell>
            <DiscardDraftDialog open={guard} mode="edit" description="Your changes to who gets this alert haven’t been applied. Closing now loses them." onKeepEditing={() => setGuard(false)} onDiscard={() => { setGuard(false); close(); }} />
        </>
    );
}
