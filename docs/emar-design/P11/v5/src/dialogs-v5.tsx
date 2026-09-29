/* P11 v5 — Stephan’s additions (30 Sep 2026): preview what an alert message looks like (Fleet “Notification preview”),
 * “What applies at this house”, and reminding people to set a witness PIN. Real components only. */
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { SelectInput } from '@/components/wizard/primitives';
import { ReviewCard, ReviewRow, WizardShell, WizardStepPane } from '@/components/wizard/shell';
import { Notice, Sections } from '@/pages/fleet-assets/settings/_ui';
import { ArrowUpRight, Bell, Clock, Eye, Home, KeyRound, LockKeyhole, Mail, Moon, Phone, Pill, Repeat, Shield, Smartphone, Users } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { ALERTS, CDW_OPTS, HOUSES, MESSAGE_SAMPLE, ONCALL_ROSTER, RECIPIENT_GROUPS, SAFETY_RULES, employee, phoneText, resolveOnCall, type HouseKey } from './data';
import { alertById, daysText, fmtMin, fmtT, has, myHouses, quietAt, ruleWhat, stamp, useStore } from './model';
import { settingsHref, useNav } from './nav';
import { canResetPin } from './settings';
import { Choice } from './ui';

const glabel = (g: string) => RECIPIENT_GROUPS[g].l;

/* ── Message preview: what an alert looks like in the bell, in an email and on a lock screen, worked out from the
 * draft (channels, privacy switch). Mirrors Fleet’s Notification preview: Sample · Rules & source. Nothing is sent. ── */
export function MessagePreview({ k: k0 }: { k: string }) {
    const { m } = useStore();
    const { close } = useNav();
    const [k, setK] = useState(ALERTS.some((a) => a.k === k0) ? k0 : 'overdue'), [step, setStep] = useState(0), [ch, setCh] = useState<'inapp' | 'email' | 'push'>('inapp');
    const a = alertById(k), x = m.draft.alerts[k], s = MESSAGE_SAMPLE[k], priv = m.draft.delivery.private === 'yes';
    const on = x[ch], text = ch === 'inapp' || !priv ? s.full : s.short;
    const chName = { inapp: 'In-app', email: 'Email', push: 'Push' }[ch];
    const body: Record<typeof ch, ReactNode> = {
        inapp: <><p className="text-[13px] font-semibold">{a.l}</p><p className="text-subtle mt-1">{text}</p><p className="text-caption mt-2">In the bell · Open to see the record</p></>,
        email: <><p className="text-caption">Subject</p><p className="text-[13px] font-semibold">{priv ? a.l : `${a.l} — ${s.full.split(' — ')[0]}`}</p><p className="text-subtle mt-2">{text}</p><p className="text-subtle mt-2">Open Oblivion Care to see the details and respond.</p><p className="text-caption mt-2">Sent to your work email</p></>,
        push: <><p className="text-caption">Oblivion Care · now</p><p className="text-[13px] font-semibold">{a.l}</p><p className="text-subtle mt-1">{text}</p><p className="text-caption mt-2">Shows on the lock screen</p></>,
    };
    return (
        <WizardShell open onClose={close} title="Message preview" description="Synthetic sample; no message is sent." railIcon={Bell} railTitle={a.l} railSub="Synthetic sample"
            steps={[{ key: 'sample', label: 'Sample', blurb: 'What people see', icon: Eye }, { key: 'source', label: 'Who and how', blurb: 'Channels and recipients', icon: Shield }]}
            stepIndex={step} onStepClick={setStep} sequential={false} pct={null} headerLabel={step ? 'Who and how' : 'Message preview'}
            footerEnd={<Button variant="outline" onClick={close}>Close preview</Button>}>
            <WizardStepPane key={step}>
                {step === 0 ? (
                    <div className="space-y-4">
                        <SelectInput value={k} onChange={setK} placeholder="Choose an alert" ariaLabel="Alert to preview" options={ALERTS.map((y) => ({ value: y.k, label: y.l }))} />
                        <Sections tabs={[{ key: 'inapp', label: 'In-app', icon: Bell }, { key: 'email', label: 'Email', icon: Mail }, { key: 'push', label: 'Push', icon: Smartphone }]} value={ch} onChange={(v) => setCh(v as typeof ch)} />
                        <Notice><span>Showing your draft. {on ? `${chName} is on for this alert.` : `${chName} is off for this alert — this is how it would look.`}{ch !== 'inapp' ? (priv ? ' Client names and medicines are left out, because “Keep client names and medicines out of email and push” is on.' : ' Client names and medicines are included — “Keep client names and medicines out of email and push” is off.') : ''}</span></Notice>
                        <ReviewCard icon={ch === 'inapp' ? Bell : ch === 'email' ? Mail : Smartphone} title={`${chName} · example`}>
                            {body[ch]}
                            <p className="text-caption mt-3">Sample content only. No real person or record is used.</p>
                        </ReviewCard>
                        {s.cd ? <p className="text-caption inline-flex items-center gap-1.5"><LockKeyhole className="size-3.5" />A controlled-medicine alert: only people with controlled-medicine access get it.</p> : null}
                    </div>
                ) : (
                    <ReviewCard icon={Shield} title="How it’s sent, and to whom">
                        <ReviewRow label="In-app" value={x.inapp ? 'On' : 'Off'} />
                        <ReviewRow label="Email" value={x.email ? 'On' : 'Off'} />
                        <ReviewRow label="Push" value={x.push ? 'On' : 'Off'} />
                        <ReviewRow label="Goes to" value={[...x.groups.map(glabel), ...x.people].join(', ') || 'Nobody'} />
                        <ReviewRow label="Follow up" value={x.followUp ? 'On — re-alert and escalation follow the Delivery settings' : 'Off — sent once'} />
                        <ReviewRow label="Email and push" value={priv ? 'Leave out client names and medicines' : 'Include client names and medicines'} />
                    </ReviewCard>
                )}
            </WizardStepPane>
        </WizardShell>
    );
}

/* ── What applies at this house: every rule in force at one house — the organisation’s, plus the house’s own. ── */
const Src = ({ house }: { house?: boolean }) => (house ? <StatusBadge variant="info" size="sm">This house</StatusBadge> : <StatusBadge variant="neutral" size="sm">Organisation</StatusBadge>);
const V = ({ v, house }: { v: ReactNode; house?: boolean }) => <span className="inline-flex flex-wrap items-center justify-end gap-2 text-right">{v}<Src house={house} /></span>;
export function HouseLens({ h: h0 }: { h: string }) {
    const { m } = useStore();
    const { close, go } = useNav();
    const mine = myHouses(m.persona);
    const [h, setH] = useState<HouseKey>((mine.includes(h0 as HouseKey) ? h0 : mine[0]) as HouseKey), [step, setStep] = useState(0);
    const s = m.saved, H = HOUSES[h];
    const opt = (key: string) => SAFETY_RULES.find((r) => r.key === key)!.opts.find((o) => o[0] === s.safety[key])?.[1].split(' — ')[0] ?? '—';
    const cdHouse = s.cdw[h], witness = cdHouse === 'org' ? s.cdw.org === 'on' : cdHouse === 'on';
    const rules = m.rules.filter((r) => r.active && (r.scope === 'all' || r.scope === h));
    const tpl = m.templates.filter((t) => t.house === h && t.status === 'active').sort((p, q) => p.time.localeCompare(q.time));
    const pins = m.pins.filter((p) => p.house === H), n = (st: string) => pins.filter((p) => p.pin === st).length;
    const extras = ALERTS.filter((a) => (s.alertExtra[`${h}.${a.k}`] ?? []).length);
    const q = quietAt(s, h), oc = m.oncall[h], tonight = oc ? resolveOnCall(oc, ONCALL_ROSTER[h][0]) : null;
    const steps = [{ key: 'rules', label: 'Medication rules', blurb: 'Checks when a dose is signed', icon: Pill }, { key: 'rounds', label: 'Rounds & timing', blurb: 'Rounds and when doses are late', icon: Repeat }, { key: 'staff', label: 'Staff & PINs', blurb: 'Competency and PINs', icon: Users }, { key: 'alerts', label: 'Alerts & on-call', blurb: 'Who is told, and who to call', icon: Bell }];
    return (
        <WizardShell open onClose={close} title={`What applies at ${H}`} description="Every rule in force at this house: the organisation’s, and the house’s own. Read-only." railIcon={Home} railTitle={H} railSub="Read-only summary"
            steps={steps} stepIndex={step} onStepClick={setStep} sequential={false} pct={null} headerLabel={steps[step].label}
            footerStart={mine.length > 1 ? <Choice value={h} onChange={(v) => setH(v as HouseKey)} options={mine.map((x) => [x, HOUSES[x]] as [HouseKey, string])} /> : undefined}
            footerEnd={<Button variant="outline" onClick={close}>Close</Button>}>
            <WizardStepPane key={`${h}-${step}`}>
                {step === 0 ? (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <ReviewCard icon={Shield} title="Safety checks">
                            <ReviewRow label="Allergy match" value={<V v={opt('profileAllergy')} />} />
                            <ReviewRow label="Competency restricted" value={<V v={opt('restricted')} />} />
                            <ReviewRow label="Area not passed" value={<V v={opt('area')} />} />
                            <ReviewRow label="Phone instructions" value={<V v={opt('phoneRx')} />} />
                        </ReviewCard>
                        <ReviewCard icon={LockKeyhole} title="Controlled drugs">
                            <ReviewRow label="Witness" value={<V v={witness ? 'Required' : 'Not required'} house={cdHouse !== 'org'} />} />
                            <ReviewRow label="Longest override" value={<V v={CDW_OPTS.longest.find((o) => o[0] === s.cdw.longest)![1].replace(' (default)', '')} />} />
                        </ReviewCard>
                        <ReviewCard icon={Pill} title={`Medicine rules that apply here (${rules.length})`} span>
                            {rules.length ? rules.map((r) => <ReviewRow key={r.id} label={ruleWhat(r)} value={<V v={[r.countersign && 'Second person confirms', r.obs.length && `Record ${r.obs.join(', ').toLowerCase()}`].filter(Boolean).join(' · ') || 'Active'} house={r.scope !== 'all'} />} />) : <p className="text-caption">No active medicine rules apply here.</p>}
                        </ReviewCard>
                    </div>
                ) : step === 1 ? (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <ReviewCard icon={Repeat} title={`Rounds (${tpl.length} active)`}>
                            {tpl.length ? tpl.map((t) => <ReviewRow key={t.id} label={`${t.name} · ${fmtT(t.time)}`} value={<V v={`${daysText(t.days)} · ±${t.win} min`} house />} />) : <p className="text-caption">No active round templates.</p>}
                        </ReviewCard>
                        <ReviewCard icon={Clock} title="Dose timing">
                            <ReviewRow label="Can be given from" value={<V v={`${s.timing.early} minutes before`} />} />
                            <ReviewRow label="Late after" value={<V v={`${s.timing.late} minutes`} />} />
                            <ReviewRow label="Late dose raises an incident" value={<V v={`After ${s.timing.lateIncident} minutes`} />} />
                            <ReviewRow label="Time-critical medicines" value={<V v={s.timing.critical.length ? s.timing.critical.map((c) => c.med).join(', ') : 'None marked'} />} />
                        </ReviewCard>
                    </div>
                ) : step === 2 ? (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <ReviewCard icon={Users} title="Competency">
                            <ReviewRow label="An assessment lasts" value={<V v={`${s.elig.validity} months`} />} />
                            <ReviewRow label="Pass mark" value={<V v={`${s.elig.passMark} of 12`} />} />
                            <ReviewRow label="Longest exemption" value={<V v={`${s.elig.longestEx} days`} />} />
                        </ReviewCard>
                        <ReviewCard icon={KeyRound} title={`Witness PINs at ${H}`}>
                            <ReviewRow label="Set" value={`${n('set')} of ${pins.length}`} />
                            <ReviewRow label="Not set yet" value={`${n('notset') + n('adminreset')}`} />
                            <ReviewRow label="Locked" value={`${n('locked')}`} />
                            <ReviewRow label="Locks after" value={<V v={`${s.pin.attempts} wrong attempts, for ${s.pin.lockout} minutes`} />} />
                        </ReviewCard>
                    </div>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <ReviewCard icon={Bell} title="Alerts">
                            <ReviewRow label="Channels" value={<V v={`In-app ${ALERTS.filter((a) => s.alerts[a.k].inapp).length} · email ${ALERTS.filter((a) => s.alerts[a.k].email).length} · push ${ALERTS.filter((a) => s.alerts[a.k].push).length} of ${ALERTS.length}`} />} />
                            <ReviewRow label="Re-alert until attended" value={<V v={s.delivery.realertEvery ? `Every ${s.delivery.realertEvery} minutes` : 'Off'} />} />
                            <ReviewRow label="Extra people here" value={<V v={extras.length ? extras.map((a) => `${a.l}: ${s.alertExtra[`${h}.${a.k}`].join(', ')}`).join('; ') : 'None'} house={!!extras.length} />} />
                        </ReviewCard>
                        <ReviewCard icon={Moon} title="Quiet hours">
                            <ReviewRow label="Non-urgent email and push" value={<V v={q ? `Held ${fmtT(q.from)} to ${fmtT(q.until)}` : 'Sent straight away'} house={s.quietHouse[h].mode !== 'org'} />} />
                        </ReviewCard>
                        <ReviewCard icon={Phone} title="On-call contact">
                            <ReviewRow label="How it’s decided" value={<V v={oc ? (oc.mode === 'roster' ? `The roster${oc.teamLead ? ', then the team lead on shift' : ''}` : 'Always the same person') : 'Not configured'} house />} />
                            <ReviewRow label="Tonight" value={tonight ? (tonight.who ? `${tonight.who.name} · ${phoneText(tonight.who)}` : tonight.warn ?? '—') : 'Screens give no number'} />
                            <ReviewRow label="Backup" value={oc ? employee(oc.person)?.name ?? '—' : '—'} />
                        </ReviewCard>
                        <ReviewCard icon={LockKeyhole} title="Emergency access">
                            <ReviewRow label="A grant lasts" value={<V v={fmtMin(s.ea.def)} />} />
                            <ReviewRow label="Longest grant" value={<V v={fmtMin(s.ea.max)} />} />
                        </ReviewCard>
                    </div>
                )}
                <p className="text-caption mt-4">Saved settings only — unsaved changes aren’t shown. <Button variant="link" onClick={() => go(settingsHref(['rules', 'rounds', 'staff', 'alerts'][step]))}>Open {steps[step].label} <ArrowUpRight className="size-4" /></Button></p>
            </WizardStepPane>
        </WizardShell>
    );
}

/* ── Remind people to set a witness PIN (PIN status). An in-app reminder, plus push if they’ve set it up. ── */
export function PinRemind({ who }: { who: string }) {
    const { m, set, flash } = useStore();
    const { close } = useNav();
    const due = m.pins.filter((p) => (p.pin === 'notset' || p.pin === 'adminreset') && canResetPin(m, p.house) && (who === 'all' || p.name === who));
    if (!due.length) return <ConfirmDialog open onClose={close} variant="default" title="Nobody to remind" confirmText="Close" description="Everyone you can remind has already set a PIN." onConfirm={() => undefined} />;
    const names = due.map((p) => p.name);
    return (
        <ConfirmDialog open onClose={close} variant="default" title={due.length === 1 ? `Remind ${names[0]} to set a witness PIN?` : `Remind ${due.length} people to set a witness PIN?`} confirmText={due.length === 1 ? 'Send reminder' : `Send ${due.length} reminders`}
            description={<>
                {due.length > 1 ? <p className="font-medium text-foreground">{names.join(', ')}</p> : null}
                <p className="mt-1">They get an in-app reminder, and push if they’ve set it up. It opens their account › Witness PIN. Until they set one, they can’t co-sign or witness.</p>
                <p className="mt-2">Recorded in the audit log with your name and the time.</p>
            </>}
            onConfirm={() => { set((d) => { d.pins.forEach((p) => { if (names.includes(p.name)) p.reminded = `Today 9:12 am, by ${stamp(d).split(',')[0]}`; }); }); flash(due.length === 1 ? `Reminder sent to ${names[0]}.` : `Reminders sent to ${due.length} people.`); }} />
    );
}
